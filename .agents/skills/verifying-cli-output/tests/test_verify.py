import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / "scripts/verify.py"
SPEC = importlib.util.spec_from_file_location("pty_verify", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class VerifyTest(unittest.TestCase):
    def recording(self, rows):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name)
        summary = {"candidate": "fixture", "label": "case", "termination": None,
                   "drained": True, "child_exit_code": 0, "capture_exit_code": 0,
                   "input_actions_sent": 0, "input_actions_expected": 0,
                   "first_output_seconds": .01, "max_idle_gap_seconds": .3}
        (root / "summary.json").write_text(json.dumps(summary))
        (root / "metadata.json").write_text(json.dumps({"candidate": "fixture", "label": "case"}))
        frames = [{"elapsed": index * .3, "lines": [row], "final": index == len(rows) - 1}
                  for index, row in enumerate(rows)]
        (root / "frames.jsonl").write_text("\n".join(json.dumps(frame) for frame in frames))
        return root

    def expectation(self):
        return {"candidate": "fixture", "label": "case", "exit_code": 0,
                "final_contains": ["Done"], "state_rows": [{
                    "name": "Task", "pattern": r"Task (?P<state>Queued|Running|Done)",
                    "states": ["Queued", "Running", "Done"],
                    "transitions": [["Queued", "Running"], ["Running", "Done"]],
                    "required": ["Queued", "Running", "Done"]}]}

    def test_accepts_monotonic_states(self):
        root = self.recording(["Task Queued", "Task Running", "Task Done"])
        self.assertTrue(MODULE.verify(root, self.expectation())["passed"])

    def test_rejects_running_to_queued_even_when_final_result_succeeds(self):
        root = self.recording(["Task Queued", "Task Running", "Task Queued", "Task Running", "Task Done"])
        result = MODULE.verify(root, self.expectation())
        self.assertFalse(result["passed"])
        self.assertIn("forbidden transition: Task Running -> Queued", result["failures"])

    def test_final_frame_alone_does_not_prove_state_sequence(self):
        result = MODULE.verify(self.recording(["Task Done"]), self.expectation())
        self.assertFalse(result["passed"])
        self.assertIn("missing state: Task Running", result["failures"])

    def test_rejects_wrong_candidate(self):
        expectation = self.expectation()
        expectation["candidate"] = "different"
        result = MODULE.verify(self.recording(["Task Queued", "Task Running", "Task Done"]), expectation)
        self.assertFalse(result["passed"])

    def animation_expectation(self):
        return {"candidate": "fixture", "label": "case", "exit_code": 0,
                       "animation_rows": [{"name": "Task", "pattern": r"(?P<glyph>[○◉]) Task",
                                           "terminal_pattern": r"Task Done",
                                           "minimum_changes": 2, "min_interval": .2, "max_interval": .4}]}

    def test_checks_actual_glyph_changes_instead_of_chunk_count(self):
        expectation = self.animation_expectation()
        self.assertTrue(MODULE.verify(self.recording(["○ Task", "◉ Task", "○ Task", "Task Done"]), expectation)["passed"])
        self.assertFalse(MODULE.verify(self.recording(["○ Task", "○ Task", "○ Task", "Task Done"]), expectation)["passed"])

    def test_initial_glyph_is_not_a_change(self):
        result = MODULE.verify(self.recording(["○ Task", "◉ Task", "Task Done"]), self.animation_expectation())
        self.assertIn("insufficient visible animation: Task", result["failures"])

    def test_rejects_frozen_active_tail_despite_other_output(self):
        result = MODULE.verify(self.recording([
            "○ Task", "◉ Task", "○ Task", "○ Task\nheartbeat 1",
            "○ Task\nheartbeat 2", "Task Done"]), self.animation_expectation())
        self.assertIn("animation remained frozen: Task", result["failures"])

    def test_rejects_silent_tail_before_terminal_frame(self):
        root = self.recording(["○ Task", "◉ Task", "○ Task", "Task Done"])
        path = root / "frames.jsonl"
        frames = [json.loads(line) for line in path.read_text().splitlines()]
        frames[-1]["elapsed"] = 4.0
        path.write_text("\n".join(json.dumps(frame) for frame in frames))
        self.assertIn("animation active tail outside bounds: Task",
                      MODULE.verify(root, self.animation_expectation())["failures"])

    def test_row_disappearing_does_not_prove_completion(self):
        result = MODULE.verify(self.recording(["○ Task", "◉ Task", "○ Task", "unrelated"]), self.animation_expectation())
        self.assertIn("animation disappeared without terminal state: Task", result["failures"])

    def test_final_active_frame_does_not_prove_completion(self):
        result = MODULE.verify(self.recording(["○ Task", "◉ Task", "○ Task"]), self.animation_expectation())
        self.assertIn("animation terminal state missing: Task", result["failures"])

    def test_rejects_unasserted_exit_only_case(self):
        with self.assertRaises(ValueError):
            MODULE.verify(self.recording(["done"]), {"candidate": "fixture", "label": "case", "exit_code": 0})


if __name__ == "__main__":
    unittest.main()
