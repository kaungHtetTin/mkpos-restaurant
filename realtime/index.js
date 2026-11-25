import { startRealtimeFromEnvironment } from './server.mjs';

try {
  startRealtimeFromEnvironment();
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
