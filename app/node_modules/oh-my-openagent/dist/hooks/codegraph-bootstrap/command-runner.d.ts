export interface CodegraphCommandResult {
    readonly exitCode: number;
    readonly stderr?: string;
    readonly stdout: string;
    readonly timedOut: boolean;
}
export interface RunCodegraphCommandOptions {
    readonly env: Record<string, string>;
    readonly timeoutMs: number;
}
export interface CodegraphCommandInvocation {
    readonly args: readonly string[];
    readonly command: string;
}
export declare function runCodegraphCommand(projectRoot: string, command: string, args: readonly string[], options: RunCodegraphCommandOptions): Promise<CodegraphCommandResult>;
export declare function resolveCodegraphCommandInvocation(command: string, args: readonly string[], platform?: NodeJS.Platform): CodegraphCommandInvocation;
