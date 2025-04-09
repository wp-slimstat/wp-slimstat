export declare const hapiErrorPlugin: {
    name: string;
    version: string;
    register: (serverArg: Record<any, any>) => Promise<void>;
};
export declare const hapiTracingPlugin: {
    name: string;
    version: string;
    register: (serverArg: Record<any, any>) => Promise<void>;
};
export type HapiOptions = {
    /** Hapi server instance */
    server?: Record<any, any>;
};
export declare const hapiIntegration: (options?: HapiOptions | undefined) => import("@sentry/types").IntegrationFnResult;
/**
 * Hapi Framework Integration.
 * @deprecated Use `hapiIntegration()` instead.
 */
export declare const Hapi: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration>;
export type Hapi = typeof Hapi;
//# sourceMappingURL=index.d.ts.map