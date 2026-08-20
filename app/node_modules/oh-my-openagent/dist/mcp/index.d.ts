import { createWebsearchConfig } from "./websearch";
import { type CodegraphMcpConfigOptions } from "./codegraph";
import { type LocalMcpConfig } from "./lsp";
import type { RuntimeExecutableResolver } from "./runtime-executable";
import type { CodegraphConfig } from "../config/schema/codegraph";
export { McpNameSchema, type McpName } from "./types";
type RemoteMcpConfig = {
    type: "remote";
    url: string;
    enabled: boolean;
    headers?: Record<string, string>;
    oauth?: false;
};
type BuiltinMcpConfig = RemoteMcpConfig | LocalMcpConfig;
type BuiltinMcpOptions = {
    readonly codegraph?: Pick<CodegraphMcpConfigOptions, "env" | "fileExists" | "homeDir" | "provisioned" | "requireResolve">;
    readonly cwd?: string;
    readonly resolveExecutable?: RuntimeExecutableResolver;
};
type BuiltinMcpSourceConfig = {
    readonly codegraph?: Partial<CodegraphConfig>;
    readonly disabled_tools?: readonly string[];
    readonly websearch?: Parameters<typeof createWebsearchConfig>[0];
};
export declare function createBuiltinMcps(disabledMcps?: string[], config?: BuiltinMcpSourceConfig, options?: BuiltinMcpOptions): Record<string, BuiltinMcpConfig>;
