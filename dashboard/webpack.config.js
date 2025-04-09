const path = require("path");
const defaultConfig = require("@wordpress/scripts/config/webpack.config");

module.exports = {
    ...defaultConfig,
    entry: {
        "slimstat-dashboard": path.resolve(process.cwd(), "src/js/dashboard.js"),
    },
    resolve: {
        ...defaultConfig.resolve,
        alias: {
            ...defaultConfig.resolve.alias,
            "@": path.resolve(__dirname, "src/App/shadcn/"),
            "@/lib": path.resolve(__dirname, "src/App/shadcn/lib"),
            "@/components": path.resolve(__dirname, "src/App/shadcn/components"),
        },
    },
    output: {
        path: path.resolve(__dirname, "dist"),
    },
    devServer: {
        hot: true,
        devMiddleware: {
            writeToDisk: true,
        },
        allowedHosts: "auto",
        host: "slimstat.local",
        port: 8887,
        proxy: {
            "/dist": {
                pathRewrite: {
                    "^/dist": "",
                },
            },
        },
    },
};
