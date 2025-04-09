import { createSlice, type PayloadAction } from "@reduxjs/toolkit";

interface DashState {
    value: number;
}

const initialState: DashState = {
    value: 0,
};

const dashSlice = createSlice({
    name: "dash",
    initialState,
    reducers: {
        increment: (state) => {
            state.value += 1;
        },
        decrement: (state) => {
            state.value -= 1;
        },
        setValue: (state, action: PayloadAction<number>) => {
            state.value = action.payload;
        },
    },
});

export const { increment, decrement, setValue } = dashSlice.actions;
export default dashSlice.reducer;
