import 'dotenv/config';
import mysql from 'mysql2/promise';
import { buildDbConfig, initializeDbConnection } from './db-config.js';

export const pool = mysql.createPool(buildDbConfig());
pool.on('connection', initializeDbConnection);

export async function withTransaction(callback) {
  const connection = await pool.getConnection();
  try {
    await connection.beginTransaction();
    const result = await callback(connection);
    await connection.commit();
    return result;
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    connection.release();
  }
}
