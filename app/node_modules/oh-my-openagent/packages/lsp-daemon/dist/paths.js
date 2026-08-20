import { createHash } from "node:crypto";
import { createRequire } from "node:module";
import { homedir, tmpdir, userInfo } from "node:os";
import * as path from "node:path";
import { fileURLToPath } from "node:url";
import { OMO_LSP_DAEMON_DIR, resolveDaemonRuntime, validateDaemonVersion, } from "./runtime-contract.js";
export { InvalidDaemonVersionError, OMO_LSP_DAEMON_DIR, OMO_LSP_DAEMON_VERSION, validateDaemonVersion, } from "./runtime-contract.js";
const requireFromHere = createRequire(import.meta.url);
const MAX_SOCKET_PATH_LENGTH = 100;
export class InvalidDaemonDirectoryError extends Error {
    constructor(directory) {
        super(`${OMO_LSP_DAEMON_DIR} must be an absolute path`);
        this.code = "invalid_daemon_directory";
        this.name = "InvalidDaemonDirectoryError";
        this.directory = directory;
    }
}
export function resolveDaemonVersion(requireFn = requireFromHere) {
    for (const candidate of ["./package.json", "../package.json"]) {
        let loaded;
        try {
            loaded = requireFn(candidate);
        }
        catch (error) {
            if (!(error instanceof Error))
                throw error;
            continue;
        }
        if (typeof loaded === "object" && loaded !== null && "version" in loaded) {
            const version = Reflect.get(loaded, "version");
            if (typeof version === "string")
                return validateDaemonVersion(version);
        }
    }
    return "0";
}
export function packagedRuntimeDefaults() {
    return {
        cliPath: fileURLToPath(new URL("./cli.js", import.meta.url)),
        version: resolveDaemonVersion(),
    };
}
export function daemonBaseDir(env = process.env, platform = defaultDaemonPlatform()) {
    const override = env[OMO_LSP_DAEMON_DIR];
    if (override !== undefined) {
        if (!platform.path.isAbsolute(override))
            throw new InvalidDaemonDirectoryError(override);
        return platform.path.resolve(override);
    }
    return platform.path.resolve(platform.path.join(platform.homedir(), ".omo", "lsp-daemon"));
}
export function daemonPaths(env = process.env, runtimeDefaults = packagedRuntimeDefaults(), platform = defaultDaemonPlatform()) {
    const runtime = resolveDaemonRuntime(env, runtimeDefaults);
    const baseDir = daemonBaseDir(env, platform);
    const dir = platform.path.resolve(platform.path.join(baseDir, `v${runtime.version}`));
    return {
        version: runtime.version,
        cliPath: runtime.cliPath,
        dir,
        socket: resolveSocketPath(dir, runtime.version, platform),
        lock: platform.path.join(dir, "daemon.lock"),
        pid: platform.path.join(dir, "daemon.pid"),
        auth: platform.path.join(dir, "daemon.auth"),
        endpoint: platform.path.join(dir, "daemon.endpoint"),
        owner: platform.path.join(dir, "daemon.owner"),
        log: platform.path.join(dir, "daemon.log"),
    };
}
function defaultDaemonPlatform() {
    return {
        platform: process.platform,
        homedir,
        tmpdir,
        getuid: () => (typeof process.getuid === "function" ? process.getuid() : undefined),
        username: () => userInfo().username,
        path,
    };
}
function resolveSocketPath(dir, version, platform) {
    const canonicalVersionDir = platform.path.resolve(dir);
    if (platform.platform === "win32") {
        const currentUserDiscriminator = `${platform.getuid() ?? "win"}:${platform.username()}:${platform.path.resolve(platform.homedir())}`;
        const digest = shortDigest(`${canonicalVersionDir}\0${currentUserDiscriminator}`);
        return `\\\\.\\pipe\\omo-lsp-${version}-${digest}`;
    }
    const natural = platform.path.join(canonicalVersionDir, "daemon.sock");
    if (natural.length < MAX_SOCKET_PATH_LENGTH)
        return natural;
    return platform.path.join(platform.tmpdir(), `omo-lsp-${version}-${shortDigest(canonicalVersionDir)}`, "daemon.sock");
}
function shortDigest(value) {
    return createHash("sha256").update(value).digest("hex").slice(0, 16);
}
