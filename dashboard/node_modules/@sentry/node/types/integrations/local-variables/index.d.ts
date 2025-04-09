import { LocalVariablesSync } from './local-variables-sync';
/**
 * Adds local variables to exception frames.
 *
 * @deprecated Use `localVariablesIntegration()` instead.
 */
export declare const LocalVariables: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration & {
    processEvent: (event: import("@sentry/types").Event) => import("@sentry/types").Event;
    setup: (client: import("../..").NodeClient) => void;
}> & (new (options?: import("./common").LocalVariablesIntegrationOptions | undefined, session?: import("./local-variables-sync").DebugSession | undefined) => import("@sentry/types").Integration);
export type LocalVariables = LocalVariablesSync;
export declare const localVariablesIntegration: (options?: import("./common").LocalVariablesIntegrationOptions | undefined, session?: import("./local-variables-sync").DebugSession | undefined) => import("@sentry/types").IntegrationFnResult;
//# sourceMappingURL=index.d.ts.map