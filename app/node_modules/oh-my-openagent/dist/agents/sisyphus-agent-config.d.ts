import type { AgentConfig } from "@opencode-ai/sdk";
import type { AgentMode } from "./types";
export declare function buildGptSisyphusAgentConfig(mode: AgentMode, model: string, prompt: string): AgentConfig;
export declare function buildGlmSisyphusAgentConfig(mode: AgentMode, model: string, prompt: string): AgentConfig;
export declare function buildClaudeSisyphusAgentConfig(mode: AgentMode, model: string, prompt: string): AgentConfig;
