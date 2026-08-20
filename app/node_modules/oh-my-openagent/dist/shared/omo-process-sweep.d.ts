import { sweepCodegraphZombies, sweepOrphanedLspDaemonProxies, sweepStaleLspDaemonVersions } from "@oh-my-opencode/utils/process-sweep";
export interface OmoFamilySweepOptions {
    readonly log?: (message: string) => void;
}
export interface OmoFamilySweeps {
    readonly sweepCodegraph: typeof sweepCodegraphZombies;
    readonly sweepLspProxies: typeof sweepOrphanedLspDaemonProxies;
    readonly sweepStaleLspDaemons: typeof sweepStaleLspDaemonVersions;
}
export declare function sweepCodegraphZombiesBestEffort(options: OmoFamilySweepOptions, sweep?: typeof sweepCodegraphZombies): Promise<void>;
export declare function sweepOrphanedLspDaemonProxiesBestEffort(options: OmoFamilySweepOptions, sweep?: typeof sweepOrphanedLspDaemonProxies): Promise<void>;
export declare function sweepStaleLspDaemonVersionsBestEffort(options: OmoFamilySweepOptions, sweep?: typeof sweepStaleLspDaemonVersions): Promise<void>;
/**
 * Runs all three omo sweep families concurrently. NEVER rejects: each family
 * is wrapped best-effort so a sweep failure can only produce a log line, not
 * a startup failure. Callers fire-and-forget the returned promise.
 */
export declare function sweepOmoFamiliesBestEffort(options?: OmoFamilySweepOptions, sweeps?: OmoFamilySweeps): Promise<void>;
