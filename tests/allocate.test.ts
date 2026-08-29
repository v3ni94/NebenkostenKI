import { describe, expect, it } from "vitest";
import { allocateByShare } from "../src/lib/calc/allocate";

describe("allocateByShare", () => {
  it("verteilt einen Betrag proportional zur Wohnfläche", () => {
    const result = allocateByShare(10_000, [
      { id: "unit-a", share: 50 },
      { id: "unit-b", share: 50 },
    ]);
    expect(result["unit-a"]).toBe(5_000);
    expect(result["unit-b"]).toBe(5_000);
  });

  it("verliert bei krummen Anteilen keinen Cent durch Rundung", () => {
    const result = allocateByShare(1_000, [
      { id: "unit-a", share: 1 },
      { id: "unit-b", share: 1 },
      { id: "unit-c", share: 1 },
    ]);
    const sum = Object.values(result).reduce((a, b) => a + b, 0);
    expect(sum).toBe(1_000);
  });

  it("wirft bei Anteilssumme 0", () => {
    expect(() => allocateByShare(1_000, [{ id: "x", share: 0 }])).toThrow();
  });
});
