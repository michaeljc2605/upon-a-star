import test from 'node:test';
import assert from 'node:assert/strict';
import { nameAfterGoogleSignIn } from './google-profile.js';

test('Google sign-in preserves a customer-edited display name', () => {
  assert.equal(nameAfterGoogleSignIn('Chosen Cinema Name', 'Google Account Name'), 'Chosen Cinema Name');
});

test('Google sign-in supplies a name for an existing account with an empty name', () => {
  assert.equal(nameAfterGoogleSignIn('   ', 'Google Account Name'), 'Google Account Name');
});
