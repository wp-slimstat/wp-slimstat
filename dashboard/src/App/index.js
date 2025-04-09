import React, { useState } from "react";
import { Provider, useSelector } from "react-redux";
import { store } from "./store/store.tsx";
import { BrowserRouter as Router, Routes, Route } from "react-router-dom";
import { LineChart, Line, XAxis, YAxis, Tooltip, ResponsiveContainer, PieChart, Pie, Cell } from "recharts";
import { VectorMap } from "react-jvectormap";
// import Dashboard from "./pages/Dashboard";
import { Card, CardContent } from "@/components/ui/card";

const trafficData = [
    { name: "Day 1", clicks: 2400 },
    { name: "Day 2", clicks: 2210 },
    { name: "Day 3", clicks: 2290 },
    { name: "Day 4", clicks: 2000 },
    { name: "Day 5", clicks: 2181 },
    { name: "Day 6", clicks: 2500 },
    { name: "Day 7", clicks: 2100 },
];

const deviceData = [
    { name: "Desktop", value: 67 },
    { name: "Tablet", value: 10 },
    { name: "Mobile", value: 23 },
];

const COLORS = ["#0088FE", "#00C49F", "#FFBB28"];

const topPages = [
    { page: "Home", views: 1340 },
    { page: "Categories", views: 1200 },
    { page: "Pros & Cons", views: 1100 },
    { page: "Post_12132", views: 980 },
    { page: "Post_1213", views: 850 },
];

const mapData = {
    DE: 36,
    CA: 36,
    IR: 36,
    FR: 30,
    PT: 30,
};

function Dashboard() {
    const { activeUsers, uniqueVisitors, clicks, pageViews, bounceRate } = useSelector((state) => state.analytics);

    const [range, setRange] = useState("7d");

    return (
        <div className="p-6 grid grid-cols-1 gap-6">
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <Card>
                    <CardContent>
                        <p>Active Users</p>
                        <h2>{activeUsers}</h2>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent>
                        <p>Unique Visitors</p>
                        <h2>{uniqueVisitors}</h2>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent>
                        <p>Clicks</p>
                        <h2>{clicks}</h2>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent>
                        <p>Page Views</p>
                        <h2>{pageViews}</h2>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent>
                        <p>Bounce Rate</p>
                        <h2>{bounceRate}</h2>
                    </CardContent>
                </Card>
            </div>

            {/* Line Chart */}
            <Card>
                <CardContent>
                    <div className="flex justify-between mb-2">
                        <h3 className="font-bold">Traffic Over Time</h3>
                        <select value={range} onChange={(e) => setRange(e.target.value)} className="text-sm border px-2 py-1 rounded">
                            <option value="7d">Last 7 Days</option>
                            <option value="30d">Last 30 Days</option>
                        </select>
                    </div>
                    <ResponsiveContainer width="100%" height={200}>
                        <LineChart data={trafficData}>
                            <XAxis dataKey="name" />
                            <YAxis />
                            <Tooltip />
                            <Line type="monotone" dataKey="clicks" stroke="#8884d8" strokeWidth={2} />
                        </LineChart>
                    </ResponsiveContainer>
                </CardContent>
            </Card>

            {/* Pie Chart */}
            <Card>
                <CardContent>
                    <h3 className="font-bold mb-2">Devices</h3>
                    <ResponsiveContainer width="100%" height={200}>
                        <PieChart>
                            <Pie data={deviceData} dataKey="value" nameKey="name" outerRadius={70}>
                                {deviceData.map((entry, index) => (
                                    <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                                ))}
                            </Pie>
                        </PieChart>
                    </ResponsiveContainer>
                </CardContent>
            </Card>

            {/* Top Pages */}
            <Card>
                <CardContent>
                    <h3 className="font-bold mb-2">Top Entry Pages</h3>
                    <ul>
                        {topPages.map((page, index) => (
                            <li key={index} className="flex justify-between py-1 border-b">
                                <span>{page.page}</span>
                                <span>{page.views} views</span>
                            </li>
                        ))}
                    </ul>
                </CardContent>
            </Card>

            {/* Geo Map */}
            <Card>
                <CardContent>
                    <h3 className="font-bold mb-2">Audience Location Map</h3>
                    <div className="w-full h-[300px]">
                        <VectorMap
                            map={"world_mill"}
                            backgroundColor="transparent"
                            zoomOnScroll={false}
                            containerStyle={{ width: "100%", height: "100%" }}
                            regionStyle={{
                                initial: {
                                    fill: "#e4e4e4",
                                    "fill-opacity": 0.9,
                                    stroke: "none",
                                    "stroke-width": 0,
                                    "stroke-opacity": 0,
                                },
                                hover: {
                                    "fill-opacity": 0.8,
                                    cursor: "pointer",
                                },
                            }}
                            series={{
                                regions: [
                                    {
                                        values: mapData,
                                        scale: ["#C8EEFF", "#0071A4"],
                                        normalizeFunction: "polynomial",
                                    },
                                ],
                            }}
                        />
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}

export default function App() {
    return (
        <Provider store={store}>
            <Router>
                <Routes>
                    <Route path="/" element={<Dashboard />} />
                </Routes>
            </Router>
        </Provider>
    );
}
