import { type DaemonOwner } from "./ownership.js";
import { type DaemonPaths } from "./paths.js";
export interface DaemonCliAttestationDeps {
    readonly readProcFile?: (path: string) => Promise<Buffer>;
    readonly executeForStdout?: (file: string, args: readonly string[]) => Promise<string | null>;
}
export interface ReapStaleDaemonVersionsDeps {
    readonly platform?: NodeJS.Platform;
    readonly isAlive?: (pid: number) => boolean;
    readonly attest?: (pid: number, platform: NodeJS.Platform) => Promise<boolean>;
    readonly sendSignal?: (pid: number, signal: NodeJS.Signals) => boolean;
    readonly waitForExit?: (pid: number, timeoutMs: number) => Promise<boolean>;
    readonly removeDir?: (path: string) => Promise<void>;
    readonly readOwner?: (paths: DaemonPaths) => DaemonOwner | null;
    readonly log?: (message: string) => void;
    readonly termGraceMs?: number;
    readonly killGraceMs?: number;
}
export type VersionReapStatus = "terminated" | "removed" | "deferred" | "spared";
export interface VersionReapResult {
    readonly version: string;
    readonly status: VersionReapStatus;
    readonly reason: string;
}
export declare function attestDaemonCliProcess(pid: number, platform: NodeJS.Platform, deps?: DaemonCliAttestationDeps): Promise<boolean>;
export declare function reapStaleDaemonVersions(ownPaths: DaemonPaths, deps?: ReapStaleDaemonVersionsDeps): Promise<readonly VersionReapResult[]>;
