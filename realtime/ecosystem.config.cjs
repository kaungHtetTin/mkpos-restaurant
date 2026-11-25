module.exports = {
  apps: [{
    name: 'restaurant-realtime',
    script: 'index.js',
    cwd: __dirname,
    instances: 1,
    exec_mode: 'fork',
    autorestart: true,
    max_memory_restart: '256M',
    env: { NODE_ENV: 'production' },
  }],
};
