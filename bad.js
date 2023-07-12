const express = require("express");
const app = express();

// prevent undefined req.body
app.use(express.json());

app.get("/platform/service", (_, res) => {
    console.log("HEARTBEAT");
    res.send("OK");
});

app.post("/platform/service", (req, res) => {
    // get and json decode the body
    const body = req.body;
    if (body.event === "echo") {
        console.log("echoing");
    }

    body.params = body.params || {};
    // simulate echo here
    res.send(JSON.stringify({ success: true, message: "OK", result: body.params }));
});

app.get("/brick", (_, res) => {
    const then = Date.now() + 5000;
    let i = 0;
    while (Date.now() < then) {
        i++;
    }
    res.send("done bricking the system: " + i);
});

app.listen(3000, () => {
    console.log('Server listening on port 3000');
});
