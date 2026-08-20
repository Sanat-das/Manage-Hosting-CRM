import type { Readable, Writable } from "node:stream";
import { type ParentWatchdogConfig } from "@oh-my-opencode/mcp-stdio-core";
import { type CallToolOptions, type DaemonToolContext } from "./daemon-client.js";
import { type DaemonPaths } from "./paths.js";
export interface ProxyOptions {
    input?: Readable;
    output?: Writable;
    stderr?: Writable;
    paths?: DaemonPaths;
    context?: DaemonToolContext;
    cwd?: string;
    env?: Record<string, string | undefined>;
    homeDir?: string;
    ensure?: CallToolOptions["ensure"];
    startupTimeoutMs?: number;
    parentWatchdog?: ParentWatchdogConfig;
}
export declare function runMcpStdioProxy(options?: ProxyOptions): Promise<void>;
