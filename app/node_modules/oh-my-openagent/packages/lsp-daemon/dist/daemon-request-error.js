export class DaemonRequestError extends Error {
    constructor(message, requestWritten) {
        super(message);
        this.name = "DaemonRequestError";
        this.requestWritten = requestWritten;
    }
}
export class DaemonAuthenticationRejectedError extends DaemonRequestError {
    constructor() {
        super("daemon authentication failed before dispatch", true);
        this.name = "DaemonAuthenticationRejectedError";
    }
}
export class DaemonRequestCancelledError extends DaemonRequestError {
    constructor(requestWritten) {
        super("daemon request cancelled", requestWritten);
        this.name = "DaemonRequestCancelledError";
    }
}
export class DaemonRequestTimedOutError extends DaemonRequestError {
    constructor(requestWritten, timeoutMs) {
        super("daemon request timed out", requestWritten);
        this.name = "DaemonRequestTimedOutError";
        this.timeoutMs = timeoutMs;
    }
}
