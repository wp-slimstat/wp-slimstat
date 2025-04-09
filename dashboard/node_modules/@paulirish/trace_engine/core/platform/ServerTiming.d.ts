export interface NameValue {
    name: string;
    value: string;
}
export interface ServerTimingParsingWarningMessage {
    deprecratedSyntax: () => string;
    duplicateParameter: (parameter: string) => string;
    noValueFoundForParameter: (parameter: string) => string;
    unrecognizedParameter: (parameter: string) => string;
    extraneousTrailingCharacters: () => string;
    unableToParseValue: (parameter: string, value: string) => string;
}
export declare class ServerTiming {
    metric: string;
    value: number;
    description: string | null;
    start: number | null;
    constructor(metric: string, value: number, description: string | null, start: number | null);
    static parseHeaders(headers: NameValue[], warningMessages?: ServerTimingParsingWarningMessage): ServerTiming[] | null;
    /**
     * TODO(crbug.com/1011811): Instead of using !Object<string, *> we should have a proper type
     *                          with #name, desc and dur properties.
     */
    static createFromHeaderValue(valueString: string, warningMessages?: ServerTimingParsingWarningMessage): Array<{
        [x: string]: any;
    }>;
    static getParserForParameter(paramName: string, warningMessages: ServerTimingParsingWarningMessage): ((arg0: {
        [x: string]: any;
    }, arg1: string | null) => void) | null;
    static showWarning(msg: string): void;
}
