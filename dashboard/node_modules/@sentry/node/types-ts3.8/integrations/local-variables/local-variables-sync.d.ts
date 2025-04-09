import { Event, Integration, IntegrationClass } from '@sentry/types';
import { Debugger, InspectorNotification } from 'inspector';
import { NodeClient } from '../../client';
import { LocalVariablesIntegrationOptions, Variables } from './common';
type OnPauseEvent = InspectorNotification<Debugger.PausedEventDataType>;
export interface DebugSession {
    /** Configures and connects to the debug session */
    configureAndConnect(onPause: (message: OnPauseEvent, complete: () => void) => void, captureAll: boolean): void;
    /** Updates which kind of exceptions to capture */
    setPauseOnExceptions(captureAll: boolean): void;
    /** Gets local variables for an objectId */
    getLocalVariables(objectId: string, callback: (vars: Variables) => void): void;
}
type Next<T> = (result: T) => void;
type Add<T> = (fn: Next<T>) => void;
type CallbackWrapper<T> = {
    add: Add<T>;
    next: Next<T>;
};
/** Creates a container for callbacks to be called sequentially */
export declare function createCallbackList<T>(complete: Next<T>): CallbackWrapper<T>;
export declare const localVariablesSyncIntegration: (options?: LocalVariablesIntegrationOptions | undefined, session?: DebugSession | undefined) => import("@sentry/types").IntegrationFnResult;
/**
 * Adds local variables to exception frames.
 * @deprecated Use `localVariablesSyncIntegration()` instead.
 */
export declare const LocalVariablesSync: IntegrationClass<Integration & {
    processEvent: (event: Event) => Event;
    setup: (client: NodeClient) => void;
}> & (new (options?: LocalVariablesIntegrationOptions, session?: DebugSession) => Integration);
export type LocalVariablesSync = typeof LocalVariablesSync;
export {};
//# sourceMappingURL=local-variables-sync.d.ts.map
