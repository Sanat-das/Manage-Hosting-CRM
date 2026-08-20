export declare class DaemonRequestError extends Error {
    readonly requestWritten: boolean;
    constructor(message: string, requestWritten: boolean);
}
export declare class DaemonAuthenticationRejectedError extends DaemonRequestError {
    constructor();
}
export declare class DaemonRequestCancelledError extends DaemonRequestError {
    constructor(requestWritten: boolean);
}
export declare class DaemonRequestTimedOutError extends DaemonRequestError {
    readonly timeoutMs: number;
    constructor(requestWritten: boolean, timeoutMs: number);
}
