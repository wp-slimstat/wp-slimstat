export type Constructor<T> = new (...args: any[]) => T;
export type AbstractConstructor<T> = (abstract new (...args: any[]) => T);
export type ConstructorOrAbstract<T> = Constructor<T> | AbstractConstructor<T>;
