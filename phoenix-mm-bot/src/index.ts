import { createBotContext } from "./config.js";
import { runBot } from "./bot.js";

async function main(): Promise<void> {
  const ctx = await createBotContext();
  await runBot(ctx);
}

main().catch((err) => {
  console.error("[fatal]", err);
  process.exit(1);
});
