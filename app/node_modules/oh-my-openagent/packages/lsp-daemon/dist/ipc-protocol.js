import { randomBytes, timingSafeEqual } from "node:crypto";
import { chmodSync, closeSync, constants, fchmodSync, fstatSync, lstatSync, mkdirSync, openSync, readFileSync, unlinkSync, writeSync, } from "node:fs";
import { dirname } from "node:path";
import { isPlainRecord } from "@oh-my-opencode/mcp-stdio-core/record";
export const OMO_DAEMON_PROTOCOL_VERSION = 1;
export const AUTH_ERROR_CODE = -32001;
export const PROTOCOL_ERROR_CODE = -32002;
const AUTH_TOKEN_BYTES = 32;
export class UnsafePrivateDirectoryError extends Error {
    constructor(path, reason) {
        super(`unsafe private directory ${path}: ${reason}`);
        this.path = path;
        this.reason = reason;
        this.name = "UnsafePrivateDirectoryError";
        this.code = "unsafe_private_directory";
    }
}
export function authEnvelope(token) {
    return { protocolVersion: OMO_DAEMON_PROTOCOL_VERSION, token };
}
export function readAuthToken(paths) {
    try {
        const token = readFileSync(paths.auth, "utf8").trim();
        return token.length > 0 ? token : null;
    }
    catch (error) {
        if (error instanceof Error)
            return null;
        throw error;
    }
}
export function readOrCreateAuthToken(paths) {
    const existing = readAuthToken(paths);
    if (existing)
        return existing;
    return createAuthToken(paths);
}
export function rotateAuthToken(paths) {
    try {
        unlinkSync(paths.auth);
    }
    catch (error) {
        if (!(error instanceof Error))
            throw error;
    }
    return createAuthToken(paths);
}
export function authenticateMessage(raw, expectedToken) {
    const id = jsonRpcId(raw);
    if (!isPlainRecord(raw))
        return authError(id);
    const params = raw["params"];
    if (!isPlainRecord(params))
        return authError(id);
    const envelope = params["_omo"];
    if (!isPlainRecord(envelope))
        return authError(id);
    const protocolVersion = envelope["protocolVersion"];
    if (protocolVersion !== OMO_DAEMON_PROTOCOL_VERSION)
        return protocolError(id);
    const token = envelope["token"];
    if (typeof token !== "string" || !tokenMatches(token, expectedToken))
        return authError(id);
    const cleanParams = { ...params };
    delete cleanParams["_omo"];
    return { input: { ...raw, params: cleanParams }, id, method: typeof raw["method"] === "string" ? raw["method"] : undefined };
}
export function isAuthErrorResponse(message) {
    if (!isPlainRecord(message))
        return false;
    const error = message["error"];
    if (!isPlainRecord(error))
        return false;
    const data = error["data"];
    return error["code"] === AUTH_ERROR_CODE && isPlainRecord(data) && data["code"] === "daemon_authentication_failed";
}
export function writePrivateFile(path, data) {
    const fd = openSync(path, "w", 0o600);
    try {
        writeSync(fd, data);
    }
    finally {
        closeSync(fd);
    }
    setPrivateFileMode(path);
}
export function ensurePrivateDirectory(path, options = {}) {
    try {
        mkdirSync(path, { recursive: true, mode: 0o700 });
    }
    catch (error) {
        if (errorCode(error) !== "EEXIST")
            throw error;
    }
    if (process.platform === "win32")
        return;
    const before = validatePrivateDirectory(path, options);
    const fd = openSync(path, constants.O_RDONLY | constants.O_DIRECTORY | constants.O_NOFOLLOW);
    try {
        fchmodSync(fd, 0o700);
        const after = validatePrivateDirectory(path, options);
        const openStats = fstatSync(fd);
        if (!sameDirectory(before, after) || !sameDirectory(before, openStats)) {
            throw new UnsafePrivateDirectoryError(path, "changed_during_chmod");
        }
    }
    finally {
        closeSync(fd);
    }
}
export function setPrivateFileMode(path) {
    if (process.platform !== "win32")
        chmodSync(path, 0o600);
}
function createAuthToken(paths) {
    ensurePrivateDirectory(dirname(paths.auth));
    const token = randomBytes(AUTH_TOKEN_BYTES).toString("base64url");
    let fd;
    try {
        fd = openSync(paths.auth, "wx", 0o600);
    }
    catch (error) {
        if (errorCode(error) === "EEXIST") {
            const existing = readAuthToken(paths);
            if (existing)
                return existing;
        }
        throw error;
    }
    try {
        writeSync(fd, `${token}\n`);
    }
    finally {
        closeSync(fd);
    }
    setPrivateFileMode(paths.auth);
    return token;
}
function errorCode(error) {
    if (!error || typeof error !== "object" || !("code" in error))
        return undefined;
    const code = Reflect.get(error, "code");
    return typeof code === "string" ? code : undefined;
}
function validatePrivateDirectory(path, options) {
    const stats = options.lstat ? options.lstat(path) : lstatPrivateDirectory(path);
    if (stats.isSymbolicLink())
        throw new UnsafePrivateDirectoryError(path, "symlink");
    if (!stats.isDirectory())
        throw new UnsafePrivateDirectoryError(path, "not_directory");
    const currentUid = options.currentUid ? options.currentUid() : process.getuid?.();
    if (currentUid !== undefined && stats.uid !== currentUid) {
        throw new UnsafePrivateDirectoryError(path, "wrong_owner");
    }
    return stats;
}
function lstatPrivateDirectory(path) {
    return lstatSync(path);
}
function sameDirectory(a, b) {
    return a.dev === b.dev && a.ino === b.ino;
}
function tokenMatches(candidate, expected) {
    const candidateBytes = Buffer.from(candidate);
    const expectedBytes = Buffer.from(expected);
    return candidateBytes.length === expectedBytes.length && timingSafeEqual(candidateBytes, expectedBytes);
}
function jsonRpcId(raw) {
    if (!isPlainRecord(raw))
        return null;
    const id = raw["id"];
    return typeof id === "string" || typeof id === "number" || id === null ? id : null;
}
function authError(id) {
    return {
        jsonrpc: "2.0",
        id,
        error: { code: AUTH_ERROR_CODE, message: "daemon authentication failed", data: { code: "daemon_authentication_failed" } },
    };
}
function protocolError(id) {
    return {
        jsonrpc: "2.0",
        id,
        error: { code: PROTOCOL_ERROR_CODE, message: "daemon protocol mismatch", data: { code: "daemon_protocol_mismatch" } },
    };
}
