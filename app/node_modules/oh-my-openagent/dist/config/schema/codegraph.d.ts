import { z } from "zod";
export declare const CodegraphConfigSchema: z.ZodObject<{
    auto_init: z.ZodDefault<z.ZodBoolean>;
    auto_provision: z.ZodDefault<z.ZodBoolean>;
    daemon: z.ZodDefault<z.ZodBoolean>;
    enabled: z.ZodDefault<z.ZodBoolean>;
    excluded_roots: z.ZodOptional<z.ZodArray<z.ZodString>>;
    install_dir: z.ZodOptional<z.ZodString>;
    telemetry: z.ZodOptional<z.ZodBoolean>;
    watch_debounce_ms: z.ZodOptional<z.ZodNumber>;
}, z.core.$strip>;
export type CodegraphConfig = z.infer<typeof CodegraphConfigSchema>;
