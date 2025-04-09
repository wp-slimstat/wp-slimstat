import App from "../App";

document.addEventListener("DOMContentLoaded", () => {
    const appContainer = document.getElementById("slimstat-dashboard");
    // Check if the app container exists before rendering
    // This prevents errors when the script is loaded on pages where the app container doesn't exist
    // and ensures that the app is only rendered when the container is present.
    // This is important for performance and avoiding unnecessary errors in the console.
    if (appContainer) {
        ReactDOM.render(<App />, appContainer);
    }
});
