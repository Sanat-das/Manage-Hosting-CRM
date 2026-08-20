import { handleLspMcpRequest } from "@oh-my-opencode/lsp-core/mcp";
import { parseLspRequestContext, runWithRequestContext } from "@oh-my-opencode/lsp-core/request-context";
import { isPlainRecord } from "@oh-my-opencode/mcp-stdio-core/record";
import { authenticateMessage, OMO_DAEMON_PROTOCOL_VERSION } from "./ipc-protocol.js";
export const CONTEXT_KEY = "_context";
class InvalidDaemonRequestError extends Error {
    constructor() {
        super(...arguments);
        this.name = "InvalidDaemonRequestError";
    }
}
export function extractRequestContext(raw) {
    if (!isPlainRecord(raw) || raw["method"] !== "tools/call")
        return { input: raw, context: undefined };
    const params = raw["params"];
    if (!isPlainRecord(params))
        throw new InvalidDaemonRequestError("Daemon tools/call params must be an object.");
    const args = params["arguments"];
    if (!isPlainRecord(args))
        throw new InvalidDaemonRequestError("Daemon tools/call arguments must be an object.");
    if (!Object.hasOwn(args, CONTEXT_KEY)) {
        throw new InvalidDaemonRequestError("Daemon tools/call arguments must include _context.");
    }
    const context = parseContext(args[CONTEXT_KEY]);
    const cleanedArgs = { ...args };
    delete cleanedArgs[CONTEXT_KEY];
    const cleaned = { ...raw, params: { ...params, arguments: cleanedArgs } };
    return { input: cleaned, context };
}
export function handleDaemonMessage(raw, state) {
    const authenticated = authenticateMessage(raw, state.token);
    if ("error" in authenticated)
        return Promise.resolve(authenticated);
    if (authenticated.method === "omo/ping") {
        return Promise.resolve({
            jsonrpc: "2.0",
            id: authenticated.id,
            result: { protocolVersion: OMO_DAEMON_PROTOCOL_VERSION, ...state.owner },
        });
    }
    if (authenticated.method === "$/cancelRequest") {
        const targetId = cancellationTargetId(authenticated.input);
        if (targetId !== undefined)
            state.activeRequests?.get(String(targetId))?.abort();
        return Promise.resolve(undefined);
    }
    let routed;
    try {
        routed = extractRequestContext(authenticated.input);
    }
    catch (error) {
        const message = error instanceof Error ? error.message : "invalid daemon request";
        return Promise.resolve({
            jsonrpc: "2.0",
            id: authenticated.id,
            error: { code: -32602, message, data: { code: "invalid_daemon_request" } },
        });
    }
    const { input, context } = routed;
    const key = routeRequestKey(authenticated.id);
    if (key === undefined || !state.activeRequests) {
        if (context)
            return runWithRequestContext(context, () => handleLspMcpRequest(input));
        return handleLspMcpRequest(input);
    }
    const controller = new AbortController();
    state.activeRequests.set(key, controller);
    const options = { signal: controller.signal };
    const run = context
        ? runWithRequestContext(context, () => handleLspMcpRequest(input, options))
        : handleLspMcpRequest(input, options);
    return run.finally(() => {
        if (state.activeRequests?.get(key) === controller)
            state.activeRequests.delete(key);
    });
}
function routeRequestKey(id) {
    return typeof id === "string" || typeof id === "number" ? String(id) : undefined;
}
function cancellationTargetId(input) {
    const params = input["params"];
    if (!isPlainRecord(params))
        return undefined;
    const id = params["id"];
    return typeof id === "string" || typeof id === "number" ? id : undefined;
}
function parseContext(value) {
    if (!isPlainRecord(value))
        throw new InvalidDaemonRequestError("LSP request _context must be an object.");
    return parseLspRequestContext(value);
}
