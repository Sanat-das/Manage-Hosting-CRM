import { type RequestContext } from "@oh-my-opencode/lsp-core/request-context";
import type { DaemonOwner } from "./ownership.js";
export declare const CONTEXT_KEY = "_context";
export interface RoutedRequest {
    input: unknown;
    context: RequestContext | undefined;
}
export declare function extractRequestContext(raw: unknown): RoutedRequest;
export type DaemonRouteState = {
    readonly token: string;
    readonly owner: DaemonOwner;
    readonly activeRequests?: Map<string, AbortController>;
};
export declare function handleDaemonMessage(raw: unknown, state: DaemonRouteState): Promise<unknown>;
