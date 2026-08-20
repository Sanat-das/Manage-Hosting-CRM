import { type LockHandle } from "./lock.js";
import type { DaemonPaths } from "./paths.js";
export type EndpointIdentity = {
    readonly kind: "unix";
    readonly path: string;
    readonly dev: number;
    readonly ino: number;
} | {
    readonly kind: "windows";
    readonly path: string;
} | {
    readonly kind: "missing";
    readonly path: string;
};
export type DaemonOwner = {
    readonly pid: number;
    readonly nonce: string;
    readonly startedAt: string;
    readonly endpoint: EndpointIdentity;
};
export type StartupLease = {
    readonly lock: LockHandle;
    readonly token: string;
    readonly owner: DaemonOwner;
};
export declare class DaemonAlreadyRunningError extends Error {
    readonly name = "DaemonAlreadyRunningError";
    readonly code = "daemon_already_running";
}
export declare class DaemonStartupDeferredError extends Error {
    readonly reason: string;
    readonly name = "DaemonStartupDeferredError";
    readonly code = "daemon_startup_deferred";
    constructor(reason: string);
}
export type OwnerPing = {
    readonly pid: number;
    readonly nonce: string;
    readonly startedAt: string;
    readonly endpoint: EndpointIdentity;
};
export declare function acquireStartupLease(paths: DaemonPaths, pingOwner: (token: string) => Promise<OwnerPing | null>): Promise<StartupLease>;
export declare function ensureDaemonDirectories(paths: DaemonPaths): void;
export declare function readDaemonOwner(paths: DaemonPaths): DaemonOwner | null;
export declare function writeDaemonOwner(paths: DaemonPaths, owner: DaemonOwner): void;
export declare function removeDaemonMetadataForOwner(paths: DaemonPaths, owner: DaemonOwner): void;
export declare function endpointIdentity(endpointPath: string): EndpointIdentity;
export declare function sameEndpoint(a: EndpointIdentity, b: EndpointIdentity): boolean;
export declare function sameOwner(a: DaemonOwner, b: DaemonOwner): boolean;
