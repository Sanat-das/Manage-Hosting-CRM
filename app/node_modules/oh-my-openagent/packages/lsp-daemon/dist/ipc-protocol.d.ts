import { type Stats } from "node:fs";
import type { DaemonPaths } from "./paths.js";
export declare const OMO_DAEMON_PROTOCOL_VERSION = 1;
export declare const AUTH_ERROR_CODE = -32001;
export declare const PROTOCOL_ERROR_CODE = -32002;
export type DaemonAuthEnvelope = {
    readonly protocolVersion: typeof OMO_DAEMON_PROTOCOL_VERSION;
    readonly token: string;
};
export type JsonRpcErrorResponse = {
    readonly jsonrpc: "2.0";
    readonly id: string | number | null;
    readonly error: {
        readonly code: number;
        readonly message: string;
        readonly data: {
            readonly code: string;
        };
    };
};
export type AuthenticatedMessage = {
    readonly input: Record<string, unknown>;
    readonly id: string | number | null;
    readonly method: string | undefined;
};
export type PrivateDirectoryStats = Pick<Stats, "dev" | "ino" | "uid" | "isDirectory" | "isSymbolicLink">;
export interface PrivateDirectoryOptions {
    readonly currentUid?: () => number | undefined;
    readonly lstat?: (path: string) => PrivateDirectoryStats;
}
export declare class UnsafePrivateDirectoryError extends Error {
    readonly path: string;
    readonly reason: "changed_during_chmod" | "not_directory" | "symlink" | "wrong_owner";
    readonly name = "UnsafePrivateDirectoryError";
    readonly code = "unsafe_private_directory";
    constructor(path: string, reason: "changed_during_chmod" | "not_directory" | "symlink" | "wrong_owner");
}
export declare function authEnvelope(token: string): DaemonAuthEnvelope;
export declare function readAuthToken(paths: DaemonPaths): string | null;
export declare function readOrCreateAuthToken(paths: DaemonPaths): string;
export declare function rotateAuthToken(paths: DaemonPaths): string;
export declare function authenticateMessage(raw: unknown, expectedToken: string): AuthenticatedMessage | JsonRpcErrorResponse;
export declare function isAuthErrorResponse(message: unknown): boolean;
export declare function writePrivateFile(path: string, data: string): void;
export declare function ensurePrivateDirectory(path: string, options?: PrivateDirectoryOptions): void;
export declare function setPrivateFileMode(path: string): void;
