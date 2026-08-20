import type { ToolExecutionResult } from "@oh-my-opencode/lsp-core/tools";
import type { DaemonPaths } from "./paths.js";
export declare function daemonFailureResult(paths: DaemonPaths, error: unknown): ToolExecutionResult;
