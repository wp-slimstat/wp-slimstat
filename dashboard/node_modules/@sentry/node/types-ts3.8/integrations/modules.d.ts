import { Event, Integration, IntegrationClass } from '@sentry/types';
export declare const modulesIntegration: () => import("@sentry/types").IntegrationFnResult;
/**
 * Add node modules / packages to the event.
 * @deprecated Use `modulesIntegration()` instead.
 */
export declare const Modules: IntegrationClass<Integration & {
    processEvent: (event: Event) => Event;
}>;
export type Modules = typeof Modules;
//# sourceMappingURL=modules.d.ts.map
