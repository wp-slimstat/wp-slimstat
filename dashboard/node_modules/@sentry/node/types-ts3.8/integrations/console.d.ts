import { Client, Integration, IntegrationClass } from '@sentry/types';
export declare const consoleIntegration: () => import("@sentry/types").IntegrationFnResult;
/**
 * Console module integration.
 * @deprecated Use `consoleIntegration()` instead.
 */
export declare const Console: IntegrationClass<Integration & {
    setup: (client: Client) => void;
}>;
export type Console = typeof Console;
//# sourceMappingURL=console.d.ts.map
