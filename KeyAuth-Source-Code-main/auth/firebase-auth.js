/**
 * Firebase Google Login Client Module for KeyAuth (DravixAuth)
 */

const firebaseConfig = {
  apiKey: "AIzaSyATpueZ9kC6RTXAItsexhzf8e7KTjHzjMI",
  authDomain: "dravixauth.firebaseapp.com",
  projectId: "dravixauth",
  storageBucket: "dravixauth.firebasestorage.app",
  messagingSenderId: "882930624511",
  appId: "1:882930624511:web:4d992cd003765ca48ee9ca",
  measurementId: "G-TSFNRSC63N"
};

// Initialize Firebase if not already initialized
if (!firebase.apps.length) {
    firebase.initializeApp(firebaseConfig);
}

function handleGoogleLogin() {
    const btn = document.getElementById("google-signin-btn");
    const statusText = document.getElementById("google-signin-status");
    
    if (btn) btn.disabled = true;
    if (statusText) statusText.innerText = "Connecting to Google...";

    const provider = new firebase.auth.GoogleAuthProvider();
    provider.addScope('email');
    provider.addScope('profile');

    firebase.auth().signInWithPopup(provider)
        .then((result) => {
            const user = result.user;
            if (statusText) statusText.innerText = "Authenticating with KeyAuth...";

            return fetch('../auth/google-login.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    email: user.email,
                    name: user.displayName,
                    photoURL: user.photoURL,
                    uid: user.uid
                })
            });
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (statusText) statusText.innerText = "Success! Redirecting...";
                window.location.href = data.redirect || '../app/';
            } else {
                alert(data.message || 'Login failed. Please try again.');
                if (btn) btn.disabled = false;
                if (statusText) statusText.innerText = "";
            }
        })
        .catch((error) => {
            console.error("Firebase Auth Error:", error);
            if (error.code !== 'auth/popup-closed-by-user') {
                alert("Google Sign-In Error: " + error.message);
            }
            if (btn) btn.disabled = false;
            if (statusText) statusText.innerText = "";
        });
}
