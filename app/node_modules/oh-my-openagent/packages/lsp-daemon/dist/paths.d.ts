import { type DaemonRuntimeDefaults } from "./runtime-contract.js";
export { InvalidDaemonVersionError, OMO_LSP_DAEMON_DIR, OMO_LSP_DAEMON_VERSION, validateDaemonVersion, } from "./runtime-contract.js";
export interface DaemonPathOperations {
    readonly isAbsolute: (value: string) => boolean;
    readonly join: (...parts: string[]) => string;
    readonly resolve: (...parts: string[]) => string;
}
export interface DaemonPlatform {
    readonly platform: NodeJS.Platform;
    readonly homedir: () => string;
    readonly tmpdir: () => string;
    readonly getuid: () => number | undefined;
    readonly username: () => string;
    readonly path: DaemonPathOperations;
}
export declare class InvalidDaemonDirectoryError extends Error {
    readonly code = "invalid_daemon_directory";
    readonly directory: string;
    constructor(directory: string);
}
export interface DaemonPaths {
    readonly version: string;
    readonly cliPath: string;
    readonly dir: string;
    readonly socket: string;
    readonly lock: string;
    readonly pid: string;
    readonly auth: string;
    readonly endpoint: string;
    readonly owner: string;
    readonly log: string;
}
export declare function resolveDaemonVersion(requireFn?: (id: string) => unknown): string;
export declare function packagedRuntimeDefaults(): DaemonRuntimeDefaults;
export declare function daemonBaseDir(env?: NodeJS.ProcessEnv, platform?: DaemonPlatform): string;
export declare function daemonPaths(env?: NodeJS.ProcessEnv, runtimeDefaults?: DaemonRuntimeDefaults, platform?: DaemonPlatform): DaemonPaths;
