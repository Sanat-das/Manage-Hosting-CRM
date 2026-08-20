import { isRecord } from "@oh-my-opencode/utils";
import type { TransformPart } from "./types";
export { isRecord };
export declare function getToolUseID(part: TransformPart): string | null;
export declare function getToolResultID(part: TransformPart): string | null;
export declare function extractUniqueToolUseIDs(parts: TransformPart[]): string[];
export declare function extractToolResultIDs(parts: TransformPart[]): Set<string>;
