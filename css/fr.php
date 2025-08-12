<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fingerprint Login (WebAuthn)</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 2em auto; }
        .container { border: 1px solid #ccc; padding: 20px; margin-bottom: 20px; border-radius: 8px; }
        input { width: calc(100% - 20px); padding: 8px; margin-bottom: 10px; }
        button { padding: 10px 15px; cursor: pointer; }
        #status { margin-top: 15px; font-weight: bold; }
    </style>
</head>
<body>

    <h1>WebAuthn Fingerprint Login Demo</h1>

    <div class="container">
        <h2>1. Register</h2>
        <label for="username-register">Username:</label>
        <input type="text" id="username-register" placeholder="Enter a username">
        <button id="register-btn">Register with Fingerprint</button>
    </div>

    <div class="container">
        <h2>2. Login</h2>
        <label for="username-login">Username:</label>
        <input type="text" id="username-login" placeholder="Enter your username">
        <button id="login-btn">Login with Fingerprint</button>
    </div>

    <p id="status"></p>

    <script >/**
 * app.js: Full Client-Side Logic for WebAuthn Fingerprint Login
 * Implemented with Vanilla JavaScript.
 *
 * Assembled on: June 22, 2025.
 *
 * This script handles:
 * 1. User registration with a fingerprint (or other platform authenticator).
 * 2. User login with a fingerprint.
 * 3. Communication with a server (mocked here for demonstration).
 *
 * This implementation is designed to work on modern browsers on desktops
 * and mobile devices (like Android) that support the WebAuthn standard.
 */

// --- 1. DOM ELEMENT SELECTION ---
// Get all the necessary elements from our index.html
const registerUsernameInput = document.getElementById('username-register');
const registerBtn = document.getElementById('register-btn');
const loginUsernameInput = document.getElementById('username-login');
const loginBtn = document.getElementById('login-btn');
const statusElem = document.getElementById('status');


// --- 2. HELPER FUNCTIONS ---
// WebAuthn uses ArrayBuffers for cryptographic operations, but servers and JSON
// use strings. These helpers convert between the two formats.

/**
 * Converts a base64url-encoded string to an ArrayBuffer.
 * This is used to decode data received from the server (like the challenge).
 * @param {string} base64url The base64url-encoded string.
 * @returns {ArrayBuffer}
 */
function base64urlToBuffer(base64url) {
    const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    const buffer = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
        buffer[i] = raw.charCodeAt(i);
    }
    return buffer;
}

/**
 * Converts an ArrayBuffer to a base64url-encoded string.
 * This is used to encode data to be sent to the server.
 * @param {ArrayBuffer} buffer The ArrayBuffer to encode.
 * @returns {string}
 */
function bufferToBase64url(buffer) {
    const bytes = new Uint8Array(buffer);
    let str = '';
    for (const charCode of bytes) {
        str += String.fromCharCode(charCode);
    }
    const base64 = window.btoa(str);
    return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}


// --- 3. REGISTRATION LOGIC ---
registerBtn.addEventListener('click', async () => {
    const username = registerUsernameInput.value;
    if (!username) {
        statusElem.textContent = 'Please enter a username to register.';
        return;
    }

    // A. Get registration options (including a challenge) from the server.
    statusElem.textContent = 'Requesting challenge from server...';
    const challengeResponse = await mockServerGetRegisterChallenge(username);
    
    // B. Prepare the options for the WebAuthn API.
    // We must convert some of the server's string data into ArrayBuffers.
    const publicKeyCredentialCreationOptions = {
        challenge: base64urlToBuffer(challengeResponse.challenge),
        rp: challengeResponse.rp,
        user: {
            ...challengeResponse.user,
            id: base64urlToBuffer(challengeResponse.user.id),
        },
        pubKeyCredParams: challengeResponse.pubKeyCredParams,
        authenticatorSelection: {
            authenticatorAttachment: "platform", // Asks for built-in sensor like a fingerprint reader
            requireResidentKey: true,
            userVerification: "required",       // This requires the user to verify (e.g., with their finger)
        },
        timeout: challengeResponse.timeout,
        attestation: challengeResponse.attestation,
    };

    // C. Call navigator.credentials.create() to trigger the browser's authenticator.
    // This is the core step that asks the user for their fingerprint on Android or other devices.
    statusElem.textContent = 'Awaiting user interaction (e.g., fingerprint scan)...';
    let credential;
    try {
        credential = await navigator.credentials.create({
            publicKey: publicKeyCredentialCreationOptions,
        });
    } catch (err) {
        statusElem.textContent = `Registration failed. Error: ${err.message}`;
        return;
    }

    // D. Send the newly created credential to the server for verification and storage.
    // We must encode the ArrayBuffers back into base64url strings for JSON transport.
    const credentialForServer = {
        id: credential.id,
        rawId: bufferToBase64url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: bufferToBase64url(credential.response.clientDataJSON),
            attestationObject: bufferToBase64url(credential.response.attestationObject),
        },
    };

    statusElem.textContent = 'Sending new credential to server for verification...';
    const verificationResponse = await mockServerVerifyRegister(credentialForServer);
    
    if (verificationResponse.verified) {
        statusElem.textContent = `Success! User "${username}" is now registered. You can now log in.`;
    } else {
        statusElem.textContent = `Failed to register on server: ${verificationResponse.error}`;
    }
});


// --- 4. LOGIN LOGIC ---
loginBtn.addEventListener('click', async () => {
    const username = loginUsernameInput.value;
    if (!username) {
        statusElem.textContent = 'Please enter your username to log in.';
        return;
    }

    // A. Get login options from the server. This includes a challenge and the ID of the allowed credential.
    statusElem.textContent = 'Requesting login challenge...';
    const challengeResponse = await mockServerGetLoginChallenge(username);

    if (!challengeResponse.allowCredentials || challengeResponse.allowCredentials.length === 0) {
        statusElem.textContent = `Could not find user "${username}" or user has no registered credentials. Please register first.`;
        return;
    }
    
    // B. Prepare the options for the WebAuthn API.
    const publicKeyCredentialRequestOptions = {
        challenge: base64urlToBuffer(challengeResponse.challenge),
        allowCredentials: challengeResponse.allowCredentials.map(cred => ({
            ...cred,
            id: base64urlToBuffer(cred.id),
        })),
        timeout: challengeResponse.timeout,
        userVerification: "required", // Request user verification for login as well.
    };
    
    // C. Call navigator.credentials.get() to trigger the authenticator for login.
    // This will prompt the user for their fingerprint to sign the challenge.
    statusElem.textContent = 'Please use your fingerprint scanner to log in...';
    let assertion;
    try {
        assertion = await navigator.credentials.get({
            publicKey: publicKeyCredentialRequestOptions
        });
    } catch (err) {
        statusElem.textContent = `Login failed. Error: ${err.message}`;
        return;
    }
    
    // D. Send the signed assertion to the server for verification.
    const assertionForServer = {
        id: assertion.id,
        rawId: bufferToBase64url(assertion.rawId),
        type: assertion.type,
        response: {
            clientDataJSON: bufferToBase64url(assertion.response.clientDataJSON),
            authenticatorData: bufferToBase64url(assertion.response.authenticatorData),
            signature: bufferToBase64url(assertion.response.signature),
            userHandle: assertion.response.userHandle ? bufferToBase64url(assertion.response.userHandle) : null,
        },
    };

    statusElem.textContent = 'Verifying login with server...';
    const verificationResponse = await mockServerVerifyLogin(assertionForServer);

    if (verificationResponse.verified) {
        statusElem.textContent = `Welcome back, ${username}! Login successful.`;
    } else {
        statusElem.textContent = `Server verification failed: ${verificationResponse.error}`;
    }
});


// =================================================================
// == MOCK SERVER - FOR DEMONSTRATION PURPOSES ONLY ==
// =================================================================
// In a real-world application, all the functions below would be
// replaced by actual `fetch()` calls to your secure backend server
// (e.g., running Node.js, Python, Java, etc.). The server would handle
// challenge generation, signature verification, and database storage.
// DO NOT use this mock implementation in production.

const userDatabase = {}; // Simple in-memory object to act as our database.

async function mockServerGetRegisterChallenge(username) {
    if (Object.keys(userDatabase).includes(username)) {
      throw new Error("User already exists");
    }
    const userId = "user-" + Date.now();
    // A real challenge would be a cryptographically secure random buffer.
    const challenge = "challenge-" + Math.random().toString().substring(2);

    userDatabase[username] = { id: userId, challenge }; 

    return {
        challenge: bufferToBase64url(new TextEncoder().encode(challenge)),
        rp: { name: "Vanilla JS Demo App" },
        user: { 
            id: bufferToBase64url(new TextEncoder().encode(userId)),
            name: username,
            displayName: username
        },
        pubKeyCredParams: [{ alg: -7, type: "public-key" }, { alg: -257, type: "public-key" }], // Supports ES256 and RS256 algorithms
        timeout: 60000,
        attestation: "direct",
    };
}

async function mockServerVerifyRegister(credential) {
    // In a real server, this is where you'd use a WebAuthn library to
    // perform complex cryptographic verification of the attestation data.
    const username = Object.keys(userDatabase).find(u => userDatabase[u].challenge);
    if(username) {
        userDatabase[username].credentialId = credential.rawId; // Store the credential ID
        userDatabase[username].verified = true;
        delete userDatabase[username].challenge; // The challenge has been used
        console.log("Registration verified. Updated DB:", userDatabase);
        return { verified: true };
    }
    return { verified: false, error: "Challenge not found for any user." };
}

async function mockServerGetLoginChallenge(username) {
    const user = userDatabase[username];
    if (!user || !user.credentialId) {
        return {}; // User not found or has no registered credentials
    }
    const challenge = "login-challenge-" + Math.random().toString().substring(2);
    user.challenge = challenge; // Store challenge for verification

    return {
        challenge: bufferToBase64url(new TextEncoder().encode(challenge)),
        allowCredentials: [{
            type: 'public-key',
            id: user.credentialId,
            transports: ['internal'], // 'internal' for built-in sensors like fingerprint readers
        }],
        timeout: 60000,
    };
}

async function mockServerVerifyLogin(assertion) {
    // A real server would look up the user by their credential ID (assertion.rawId),
    // retrieve their stored public key, and cryptographically verify that the
    // signature is valid for the challenge.
    const user = Object.values(userDatabase).find(u => u.credentialId === assertion.rawId);
    if (user && user.challenge) {
        console.log("Login assertion verified for user:", user);
        delete user.challenge;
        return { verified: true };
    }
    return { verified: false, error: "Credential not recognized or challenge mismatch." };
}</script>
</body>
</html>