import type { CodegraphCommandResult } from "./command-runner";
type CodegraphStatusDecision = {
    readonly kind: "init";
} | {
    readonly kind: "skip";
    readonly reason: string;
} | {
    readonly kind: "sync";
};
export declare function decideCodegraphStartupAction(status: CodegraphCommandResult): CodegraphStatusDecision;
export {};
