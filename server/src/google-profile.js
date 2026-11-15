/** Keep a name the customer edited in the app when Google sign-in refreshes identity. */
export function nameAfterGoogleSignIn(savedName, googleName) {
  return typeof savedName === 'string' && savedName.trim() !== '' ? savedName : googleName;
}
