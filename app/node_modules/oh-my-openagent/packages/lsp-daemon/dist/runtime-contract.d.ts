export declare const OMO_LSP_DAEMON_DIR = "OMO_LSP_DAEMON_DIR";
export declare const OMO_LSP_DAEMON_CLI = "OMO_LSP_DAEMON_CLI";
export declare const OMO_LSP_DAEMON_VERSION = "OMO_LSP_DAEMON_VERSION";
export interface DaemonRuntime {
    readonly cliPath: string;
    readonly version: string;
}
export type DaemonRuntimeDefaults = DaemonRuntime;
export type InvalidRuntimeOverrideReason = "paired_values_required" | "cli_must_be_absolute" | "cli_not_found" | "cli_not_file" | "packaged_cli_must_be_absolute";
export declare class InvalidRuntimeOverrideError extends Error {
    readonly code = "invalid_runtime_override";
    readonly reason: InvalidRuntimeOverrideReason;
    constructor(reason: InvalidRuntimeOverrideReason, message: string);
}
export declare class InvalidDaemonVersionError extends Error {
    readonly code = "invalid_daemon_version";
    readonly version: string;
    constructor(version: string);
}
export declare function validateDaemonVersion(version: string): string;
export declare function resolveDaemonRuntime(env: NodeJS.ProcessEnv, defaults: DaemonRuntimeDefaults): DaemonRuntime;
