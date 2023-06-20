const express = require("express");
const app = express();


app.get("/heartbeat", (req, res) => {
    res.send("1");
});

app.get("/event", (req, res) => {
    res.send("hi");
});

app.get("/brick", (req, res) => {
    const then = Date.now() + 5000;
    let i = 0;
    while (Date.now() < then) {
        i++;
    }
    res.send("done bricking the system");
});

app.listen(3000, () => {
    console.log('Server listening on port 3000');
});
