import { z } from "zod";
export declare const BuiltinCommandNameSchema: z.ZodEnum<{
    goal: "goal";
    hyperplan: "hyperplan";
    refactor: "refactor";
    "remove-ai-slops": "remove-ai-slops";
    "start-work": "start-work";
    "stop-continuation": "stop-continuation";
}>;
export type BuiltinCommandName = z.infer<typeof BuiltinCommandNameSchema>;
