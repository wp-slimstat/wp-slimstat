import { configureStore } from "@reduxjs/toolkit";
import dashSlice from "../slices/dashSlice";

const store = configureStore({
    reducer: {
        dash: dashSlice,
    },
});

export type RootState = ReturnType<typeof store.getState>;
export type AppDispatch = typeof store.dispatch;

export default store;
