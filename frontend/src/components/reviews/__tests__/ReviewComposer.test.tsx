import { fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { describe, expect, it } from "vitest";
import type { ReviewDraftData } from "../../../utils/draftStorage";
import { createEmptyReviewDraft, ReviewComposer } from "../ReviewComposer";

function ControlledComposer() {
  const [draft, setDraft] = useState<ReviewDraftData>(createEmptyReviewDraft);
  return <ReviewComposer value={draft} onChange={setDraft} />;
}

describe("ReviewComposer", () => {
  it("expands on star tap, supports currency/spend tier/vibes, and resets on Skip review", () => {
    render(<ControlledComposer />);

    expect(screen.queryByRole("combobox", { name: /currency/i })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /rate 5 stars/i }));
    expect(screen.getByText(/5 ★ · life-changing/i)).toBeInTheDocument();

    const currencySelect = screen.getByRole("combobox", { name: /currency/i });
    expect(currencySelect).toHaveValue("CHF");
    fireEvent.change(currencySelect, { target: { value: "EUR" } });
    expect(currencySelect).toHaveValue("EUR");

    fireEvent.click(screen.getByRole("button", { name: "$$" }));
    expect(screen.getByRole("button", { name: "$$" })).toHaveAttribute("aria-pressed", "true");

    fireEvent.click(screen.getByRole("button", { name: /must-order/i }));
    expect(screen.getByRole("button", { name: /must-order/i })).toHaveAttribute("aria-pressed", "true");

    fireEvent.click(screen.getByRole("button", { name: /skip review/i }));
    expect(screen.queryByRole("combobox", { name: /currency/i })).not.toBeInTheDocument();
  });

  it("adds and removes ordered dish/drink items with verdict pills and notes", () => {
    render(<ControlledComposer />);

    fireEvent.click(screen.getByRole("button", { name: /rate 4 stars/i }));
    fireEvent.click(screen.getByRole("button", { name: /add dish \/ item you ordered/i }));

    const dishInput = screen.getByLabelText(/dish or drink name/i);
    fireEvent.change(dishInput, { target: { value: "Pistachio Escargot" } });
    fireEvent.change(screen.getByLabelText(/item price/i), { target: { value: "4.80" } });
    fireEvent.change(screen.getByLabelText(/item note/i), { target: { value: "Warm & flaky" } });
    fireEvent.click(screen.getByRole("button", { name: /good/i }));

    expect(dishInput).toHaveValue("Pistachio Escargot");
    expect(screen.getByRole("button", { name: /good/i })).toHaveAttribute("aria-pressed", "true");

    fireEvent.click(screen.getByRole("button", { name: /remove item/i }));
    expect(screen.queryByLabelText(/dish or drink name/i)).not.toBeInTheDocument();
  });
});
