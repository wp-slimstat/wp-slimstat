import { Client, Integration, IntegrationClass } from '@sentry/types';
type UnhandledRejectionMode = 'none' | 'warn' | 'strict';
interface OnUnhandledRejectionOptions {
    /**
     * Option deciding what to do after capturing unhandledRejection,
     * that mimicks behavior of node's --unhandled-rejection flag.
     */
    mode: UnhandledRejectionMode;
}
export declare const onUnhandledRejectionIntegration: (options?: Partial<OnUnhandledRejectionOptions> | undefined) => import("@sentry/types").IntegrationFnResult;
/**
 * Global Promise Rejection handler.
 * @deprecated Use `onUnhandledRejectionIntegration()` instead.
 */
export declare const OnUnhandledRejection: IntegrationClass<Integration & {
    setup: (client: Client) => void;
}> & (new (options?: Partial<{
    mode: UnhandledRejectionMode;
}>) => Integration);
export type OnUnhandledRejection = typeof OnUnhandledRejection;
/**
 * Send an exception with reason
 * @param reason string
 * @param promise promise
 *
 * Exported only for tests.
 */
export declare function makeUnhandledPromiseHandler(client: Client, options: OnUnhandledRejectionOptions): (reason: unknown, promise: unknown) => void;
export {};
//# sourceMappingURL=onunhandledrejection.d.ts.map
