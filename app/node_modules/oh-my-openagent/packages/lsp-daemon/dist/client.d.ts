export declare const OMO_LSP_DAEMON_DIR = "OMO_LSP_DAEMON_DIR";
export declare const OMO_LSP_DAEMON_CLI = "OMO_LSP_DAEMON_CLI";
export declare const OMO_LSP_DAEMON_VERSION = "OMO_LSP_DAEMON_VERSION";
export type DaemonRuntime = {
    readonly cliPath: string;
    readonly version: string;
};
export type DaemonRuntimeDefaults = DaemonRuntime;
export type InvalidRuntimeOverrideReason = "paired_values_required" | "cli_must_be_absolute" | "cli_not_found" | "cli_not_file" | "packaged_cli_must_be_absolute";
export declare class InvalidRuntimeOverrideError extends Error {
    readonly code = "invalid_runtime_override";
    readonly reason: InvalidRuntimeOverrideReason;
    constructor(reason: InvalidRuntimeOverrideReason, message: string);
}
export declare function validateDaemonVersion(version: string): string;
export declare function resolveDaemonRuntime(env: Record<string, string | undefined>, defaults: DaemonRuntimeDefaults): DaemonRuntime;
export type LspRequestCapabilities = {
    readonly installDecisionTool: boolean;
};
export type LspRequestContext = {
    readonly cwd: string;
    readonly projectConfigPaths: readonly string[];
    readonly userConfigPath: string;
    readonly installDecisionsPath: string;
    readonly capabilities: LspRequestCapabilities;
};
export type DaemonToolContext = LspRequestContext;
export type CallToolOptions = {
    readonly context: DaemonToolContext;
    readonly requestTimeoutMs?: number;
    readonly signal?: AbortSignal;
};
export type TextContent = {
    readonly type: "text";
    readonly text: string;
};
export type ToolExecutionResult = {
    readonly content: readonly TextContent[];
    readonly isError?: boolean;
    readonly details?: unknown;
};
export type SeverityFilter = "error" | "warning" | "information" | "hint" | "all";
export type LspDiagnosticsDetails = {
    readonly filePath: string;
    readonly severity: SeverityFilter;
    readonly mode: "file" | "directory";
    readonly diagnostics: readonly unknown[];
    readonly totalDiagnostics: number;
    readonly truncated: boolean;
    readonly error?: string;
    readonly errorKind?: "freshness_timeout" | "missing_dependency" | "no_files" | "invalid_path";
    readonly fileFailures?: readonly {
        readonly file: string;
        readonly error: string;
    }[];
};
export type LspGotoDefinitionDetails = {
    readonly filePath: string;
    readonly line: number;
    readonly character: number;
    readonly locations: readonly unknown[];
    readonly error?: string;
    readonly errorKind?: "missing_dependency";
};
export type LspFindReferencesDetails = {
    readonly filePath: string;
    readonly line: number;
    readonly character: number;
    readonly references: readonly unknown[];
    readonly totalReferences: number;
    readonly truncated: boolean;
    readonly error?: string;
    readonly errorKind?: "missing_dependency";
};
export type LspSymbolsDetails = {
    readonly filePath: string;
    readonly scope: "document" | "workspace";
    readonly query?: string;
    readonly symbols: readonly unknown[];
    readonly totalSymbols: number;
    readonly truncated: boolean;
    readonly error?: string;
    readonly errorKind?: "missing_dependency" | "missing_query";
};
export type LspPrepareRenameDetails = {
    readonly filePath: string;
    readonly line: number;
    readonly character: number;
    readonly result: unknown;
    readonly error?: string;
    readonly errorKind?: "missing_dependency";
};
export type LspRenameDetails = {
    readonly filePath: string;
    readonly line: number;
    readonly character: number;
    readonly newName: string;
    readonly apply: unknown;
    readonly edit: unknown;
    readonly error?: string;
    readonly errorKind?: "missing_dependency";
};
export declare function callToolViaDaemon(name: string, args: Record<string, unknown>, options: CallToolOptions): Promise<ToolExecutionResult>;
export declare function callDiagnosticsViaDaemon(filePath: string, options: CallToolOptions): Promise<ToolExecutionResult>;
export declare function currentRequestContext(env?: Record<string, string | undefined>): DaemonToolContext;
