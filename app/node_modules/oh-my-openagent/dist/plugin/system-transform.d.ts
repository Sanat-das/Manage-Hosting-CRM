import type { DefaultModeConfig } from "../config/schema/default-mode";
export declare function createSystemTransformHandler(defaultMode?: DefaultModeConfig, getUltraworkMessage?: (agentName?: string, modelID?: string) => string): (input: {
    sessionID?: string;
    model: {
        id: string;
        providerID: string;
        [key: string]: unknown;
    };
}, output: {
    system: string[];
}) => Promise<void>;
