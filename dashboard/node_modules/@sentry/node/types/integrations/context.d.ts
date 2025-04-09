/// <reference types="node" />
import { readFile, readdir } from 'fs';
import * as os from 'os';
import type { DeviceContext, Event, Integration, IntegrationClass } from '@sentry/types';
export declare const readFileAsync: typeof readFile.__promisify__;
export declare const readDirAsync: typeof readdir.__promisify__;
interface DeviceContextOptions {
    cpu?: boolean;
    memory?: boolean;
}
interface ContextOptions {
    app?: boolean;
    os?: boolean;
    device?: DeviceContextOptions | boolean;
    culture?: boolean;
    cloudResource?: boolean;
}
export declare const nodeContextIntegration: (options?: ContextOptions | undefined) => import("@sentry/types").IntegrationFnResult;
/**
 * Add node modules / packages to the event.
 * @deprecated Use `nodeContextIntegration()` instead.
 */
export declare const Context: IntegrationClass<Integration & {
    processEvent: (event: Event) => Promise<Event>;
}> & (new (options?: {
    app?: boolean;
    os?: boolean;
    device?: {
        cpu?: boolean;
        memory?: boolean;
    } | boolean;
    culture?: boolean;
    cloudResource?: boolean;
}) => Integration);
export type Context = typeof Context;
/**
 * Gets device information from os
 */
export declare function getDeviceContext(deviceOpt: DeviceContextOptions | true): DeviceContext;
export {};
//# sourceMappingURL=context.d.ts.map