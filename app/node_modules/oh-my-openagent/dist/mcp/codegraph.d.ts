import type { ResolveCodegraphCommandOptions } from "@oh-my-opencode/utils";
import type { CodegraphConfig } from "../config/schema/codegraph";
import type { LocalMcpConfig } from "./lsp";
import { type RuntimeExecutableResolver } from "./runtime-executable";
export type CodegraphMcpConfigOptions = {
    readonly config?: Partial<CodegraphConfig>;
    readonly cwd?: string;
    readonly env?: ResolveCodegraphCommandOptions["env"];
    readonly fileExists?: ResolveCodegraphCommandOptions["fileExists"];
    readonly homeDir?: string;
    readonly nodeVersionForExecutable?: ResolveCodegraphCommandOptions["nodeVersion"];
    readonly provisioned?: ResolveCodegraphCommandOptions["provisioned"];
    readonly requireResolve?: ResolveCodegraphCommandOptions["requireResolve"];
    readonly resolveExecutable?: RuntimeExecutableResolver;
};
export declare function createCodegraphMcpConfig(options?: CodegraphMcpConfigOptions): LocalMcpConfig;
