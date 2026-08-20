import { randomUUID } from "node:crypto";
import { existsSync, lstatSync, readFileSync, statSync, unlinkSync } from "node:fs";
import { dirname } from "node:path";
import { tryAcquireLock, isProcessAlive, unlinkQuietly } from "./lock.js";
import { ensurePrivateDirectory, readAuthToken, readOrCreateAuthToken, rotateAuthToken, writePrivateFile, } from "./ipc-protocol.js";
export class DaemonAlreadyRunningError extends Error {
    constructor() {
        super(...arguments);
        this.name = "DaemonAlreadyRunningError";
        this.code = "daemon_already_running";
    }
}
export class DaemonStartupDeferredError extends Error {
    constructor(reason) {
        super(`LSP daemon startup deferred: ${reason}`);
        this.reason = reason;
        this.name = "DaemonStartupDeferredError";
        this.code = "daemon_startup_deferred";
    }
}
export async function acquireStartupLease(paths, pingOwner) {
    ensureDaemonDirectories(paths);
    const lock = tryAcquireLock(paths.lock);
    if (!lock) {
        const token = readAuthToken(paths);
        if (token && (await pingOwner(token)))
            throw new DaemonAlreadyRunningError("LSP daemon already running");
        throw new DaemonStartupDeferredError("startup_lock_busy");
    }
    try {
        const token = await validateExistingOwner(paths, pingOwner);
        return { lock, token, owner: newOwner(paths) };
    }
    catch (error) {
        lock.release();
        throw error;
    }
}
export function ensureDaemonDirectories(paths) {
    ensurePrivateDirectory(paths.dir);
    if (process.platform !== "win32")
        ensurePrivateDirectory(dirname(paths.socket));
}
export function readDaemonOwner(paths) {
    try {
        return parseOwner(JSON.parse(readFileSync(paths.owner, "utf8")));
    }
    catch (error) {
        if (error instanceof Error)
            return null;
        throw error;
    }
}
export function writeDaemonOwner(paths, owner) {
    writePrivateFile(paths.pid, `${owner.pid}\n`);
    writePrivateFile(paths.endpoint, owner.endpoint.path);
    writePrivateFile(paths.owner, `${JSON.stringify(owner)}\n`);
}
export function removeDaemonMetadataForOwner(paths, owner) {
    const current = readDaemonOwner(paths);
    if (!current || !sameOwner(current, owner))
        return;
    unlinkQuietly(paths.socket);
    unlinkQuietly(paths.pid);
    unlinkQuietly(paths.endpoint);
    unlinkQuietly(paths.owner);
}
export function endpointIdentity(endpointPath) {
    if (process.platform === "win32")
        return { kind: "windows", path: endpointPath };
    try {
        const stats = statSync(endpointPath);
        return { kind: "unix", path: endpointPath, dev: stats.dev, ino: stats.ino };
    }
    catch (error) {
        if (error instanceof Error)
            return { kind: "missing", path: endpointPath };
        throw error;
    }
}
export function sameEndpoint(a, b) {
    if (a.kind !== b.kind || a.path !== b.path)
        return false;
    switch (a.kind) {
        case "unix":
            return b.kind === "unix" && a.dev === b.dev && a.ino === b.ino;
        case "windows":
            return true;
        case "missing":
            return true;
    }
}
export function sameOwner(a, b) {
    return a.pid === b.pid && a.nonce === b.nonce && sameEndpoint(a.endpoint, b.endpoint);
}
async function validateExistingOwner(paths, pingOwner) {
    let token = readOrCreateAuthToken(paths);
    for (let attempt = 0; attempt < 2; attempt += 1) {
        const owner = readDaemonOwner(paths);
        if (!owner)
            return token;
        const ping = await pingOwner(token);
        if (ping && owner.nonce === ping.nonce && sameEndpoint(owner.endpoint, ping.endpoint)) {
            throw new DaemonAlreadyRunningError("LSP daemon already running");
        }
        if (ping)
            continue;
        if (isProcessAlive(owner.pid))
            throw new DaemonStartupDeferredError("owner_pid_live_unreachable");
        const reread = readDaemonOwner(paths);
        const endpoint = endpointIdentity(owner.endpoint.path);
        if (!reread || reread.nonce !== owner.nonce || !sameEndpoint(endpoint, owner.endpoint)) {
            throw new DaemonStartupDeferredError("owner_changed_during_cleanup");
        }
        cleanupDeadOwner(paths, owner);
        token = rotateAuthToken(paths);
        return token;
    }
    throw new DaemonStartupDeferredError("reachable_owner_mismatch");
}
function cleanupDeadOwner(paths, owner) {
    if (process.platform !== "win32" && existsSync(owner.endpoint.path)) {
        const stat = lstatSync(owner.endpoint.path);
        if (stat.isSocket())
            unlinkSync(owner.endpoint.path);
    }
    unlinkQuietly(paths.pid);
    unlinkQuietly(paths.endpoint);
    unlinkQuietly(paths.owner);
}
function newOwner(paths) {
    return {
        pid: process.pid,
        nonce: randomUUID(),
        startedAt: new Date().toISOString(),
        endpoint: endpointIdentity(paths.socket),
    };
}
function parseOwner(value) {
    if (!value || typeof value !== "object" || Array.isArray(value))
        return null;
    const pid = Reflect.get(value, "pid");
    const nonce = Reflect.get(value, "nonce");
    const startedAt = Reflect.get(value, "startedAt");
    const endpoint = parseEndpoint(Reflect.get(value, "endpoint"));
    if (typeof pid !== "number" || typeof nonce !== "string" || typeof startedAt !== "string" || !endpoint)
        return null;
    return { pid, nonce, startedAt, endpoint };
}
function parseEndpoint(value) {
    if (!value || typeof value !== "object" || Array.isArray(value))
        return null;
    const kind = Reflect.get(value, "kind");
    const path = Reflect.get(value, "path");
    if (typeof path !== "string")
        return null;
    if (kind === undefined)
        return endpointIdentity(path);
    if (kind === "windows")
        return { kind, path };
    if (kind === "missing")
        return { kind, path };
    const dev = Reflect.get(value, "dev");
    const ino = Reflect.get(value, "ino");
    if (kind === "unix" && typeof dev === "number" && typeof ino === "number")
        return { kind, path, dev, ino };
    return null;
}
