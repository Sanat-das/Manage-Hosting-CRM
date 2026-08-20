import { execFile } from "node:child_process";
import { readdir, readFile, rm } from "node:fs/promises";
import { basename, dirname, join } from "node:path";
import { isProcessAlive, readLockPid } from "./lock.js";
import { readDaemonOwner } from "./ownership.js";
import { daemonPaths, OMO_LSP_DAEMON_DIR } from "./paths.js";
const TERM_GRACE_MS = 5_000;
const KILL_GRACE_MS = 1_000;
const VERSION_ENTRY_PATTERN = /^v([A-Za-z0-9][A-Za-z0-9._+-]{0,127})$/;
export async function attestDaemonCliProcess(pid, platform, deps = {}) {
    if (platform === "win32")
        return false;
    if (platform === "linux") {
        const readProcFile = deps.readProcFile ?? defaultReadProcFile;
        const cmdline = await readProcFile(`/proc/${pid}/cmdline`).catch(() => null);
        if (cmdline === null)
            return false;
        return isNodeCliDaemonArgv(splitCmdline(cmdline));
    }
    const executeForStdout = deps.executeForStdout ?? defaultExecuteForStdout;
    const command = await executeForStdout("/bin/ps", ["-p", String(pid), "-o", "command="]);
    if (command === null)
        return false;
    return isNodeCliDaemonCommand(command.trim());
}
export async function reapStaleDaemonVersions(ownPaths, deps = {}) {
    const baseDir = dirname(ownPaths.dir);
    const platform = deps.platform ?? process.platform;
    const isAlive = deps.isAlive ?? isProcessAlive;
    const attest = deps.attest ?? ((pid) => attestDaemonCliProcess(pid, platform));
    const sendSignal = deps.sendSignal ?? defaultSendSignal;
    const waitForExit = deps.waitForExit ?? defaultWaitForExit;
    const removeDir = deps.removeDir ?? ((path) => rm(path, { recursive: true, force: true }));
    const readOwner = deps.readOwner ?? readDaemonOwner;
    const log = deps.log ?? defaultLog;
    const termGraceMs = deps.termGraceMs ?? TERM_GRACE_MS;
    const killGraceMs = deps.killGraceMs ?? KILL_GRACE_MS;
    const entries = await readdir(baseDir, { withFileTypes: true }).catch(() => []);
    const results = [];
    for (const entry of [...entries].sort((left, right) => left.name.localeCompare(right.name))) {
        if (entry.name === `v${ownPaths.version}`)
            continue;
        const version = parseVersionEntry(entry.name);
        if (version === null || !entry.isDirectory())
            continue;
        const versionDir = join(baseDir, entry.name);
        const siblingPaths = daemonPaths({ [OMO_LSP_DAEMON_DIR]: baseDir }, { cliPath: ownPaths.cliPath, version });
        results.push(await reapOneVersion({
            version,
            versionDir,
            siblingPaths,
            platform,
            isAlive,
            attest,
            sendSignal,
            waitForExit,
            removeDir,
            readOwner,
            log,
            termGraceMs,
            killGraceMs,
        }));
    }
    return results;
}
async function reapOneVersion(context) {
    const { version, versionDir } = context;
    const owner = context.readOwner(context.siblingPaths);
    if (!owner) {
        const lockPid = readLockPid(join(versionDir, "daemon.lock"));
        if (lockPid !== null && context.isAlive(lockPid)) {
            context.log(`reap: sparing v${version}: owner metadata missing but daemon.lock is held by live pid ${lockPid}`);
            return { version, status: "spared", reason: `owner metadata missing but lock held by live pid ${lockPid}` };
        }
        await context.removeDir(versionDir);
        return { version, status: "removed", reason: "removed stale version dir without readable owner metadata" };
    }
    if (!context.isAlive(owner.pid)) {
        await context.removeDir(versionDir);
        return { version, status: "removed", reason: `removed stale version dir for dead owner pid ${owner.pid}` };
    }
    if (context.platform === "win32") {
        context.log(`reap: deferring v${version}: Windows cannot prove pid ownership safely (named-pipe policy)`);
        return {
            version,
            status: "deferred",
            reason: "Windows cannot prove pid ownership safely; named-pipe reap deferred",
        };
    }
    if (!(await context.attest(owner.pid, context.platform))) {
        context.log(`reap: sparing v${version}: pid ${owner.pid} is alive but cmdline attestation failed (possible recycled pid)`);
        return {
            version,
            status: "spared",
            reason: `pid ${owner.pid} attestation failed; possible recycled pid`,
        };
    }
    return await terminateAttestedOwner(context, owner.pid);
}
async function terminateAttestedOwner(context, pid) {
    const { version, versionDir } = context;
    if (!context.sendSignal(pid, "SIGTERM")) {
        await context.removeDir(versionDir);
        return {
            version,
            status: "removed",
            reason: `owner pid ${pid} exited before SIGTERM; removed stale version dir`,
        };
    }
    if (await context.waitForExit(pid, context.termGraceMs)) {
        await context.removeDir(versionDir);
        return { version, status: "terminated", reason: `terminated attested older daemon pid ${pid} with SIGTERM` };
    }
    context.log(`reap: v${version} pid ${pid} survived SIGTERM; escalating to SIGKILL`);
    if (!context.sendSignal(pid, "SIGKILL") || !(await context.waitForExit(pid, context.killGraceMs))) {
        context.log(`reap: deferring v${version}: pid ${pid} survived SIGKILL`);
        return { version, status: "deferred", reason: `attested daemon pid ${pid} survived SIGKILL; dir kept` };
    }
    await context.removeDir(versionDir);
    return {
        version,
        status: "terminated",
        reason: `terminated attested older daemon pid ${pid} after SIGKILL escalation`,
    };
}
function parseVersionEntry(entryName) {
    const match = VERSION_ENTRY_PATTERN.exec(entryName);
    return match?.[1] ?? null;
}
function splitCmdline(buffer) {
    return buffer
        .toString("utf8")
        .split("\u0000")
        .filter((value) => value.length > 0);
}
function isNodeCliDaemonArgv(argv) {
    if (argv.length < 2 || !argv.includes("daemon"))
        return false;
    const executable = basename(argv[0] ?? "");
    if (!/^node(?:\.exe)?$/i.test(executable))
        return false;
    return argv.some((value) => value === "cli.js" || value.endsWith("/cli.js") || value.endsWith("\\cli.js"));
}
function isNodeCliDaemonCommand(command) {
    return /\bnode(?:\.exe)?\b/i.test(command) && /\bcli\.js\b/.test(command) && /\bdaemon\b/.test(command);
}
function defaultReadProcFile(path) {
    return readFile(path);
}
function defaultExecuteForStdout(file, args) {
    return new Promise((resolve) => {
        execFile(file, [...args], { encoding: "utf8", maxBuffer: 1024 * 1024, timeout: 1_000 }, (error, stdout) => {
            if (error !== null) {
                resolve(null);
                return;
            }
            resolve(stdout);
        });
    });
}
function defaultSendSignal(pid, signal) {
    try {
        process.kill(pid, signal);
        return true;
    }
    catch {
        return false;
    }
}
async function defaultWaitForExit(pid, timeoutMs) {
    const deadline = Date.now() + timeoutMs;
    for (;;) {
        if (!isProcessAlive(pid))
            return true;
        if (Date.now() >= deadline)
            return false;
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
}
function defaultLog(message) {
    process.stderr.write(`[lsp-daemon] ${message}\n`);
}
