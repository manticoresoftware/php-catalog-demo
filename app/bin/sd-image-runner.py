#!/usr/bin/env python3

import json
import sys

import torch
from diffusers import StableDiffusionPipeline


def main() -> int:
    if len(sys.argv) < 2:
        print("Usage: sd-image-runner.py <config-json-path>", file=sys.stderr)
        return 2

    cfg_path = sys.argv[1]
    with open(cfg_path, "r", encoding="utf-8") as fh:
        cfg = json.load(fh)

    device = cfg.get("device", "cuda")
    torch_dtype = torch.float16 if device == "cuda" else torch.float32
    pipe = StableDiffusionPipeline.from_pretrained(
        cfg["model_id"],
        torch_dtype=torch_dtype,
    ).to(device)
    pipe.enable_attention_slicing()

    base_seed = int(cfg["seed"])
    steps = int(cfg.get("steps", 26))
    guidance = float(cfg.get("cfg_scale", 7.0))
    width = int(cfg.get("width", 512))
    height = int(cfg.get("height", 512))
    negative = cfg.get("negative_prompt", "")

    for idx, job in enumerate(cfg["jobs"]):
        prompt = (job.get("prompt") or "").strip() or "A highly detailed board game product photo."
        out_path = job["path"]
        job_width = int(job.get("width", width))
        job_height = int(job.get("height", height))
        seed = base_seed + idx
        generator = torch.Generator(device=device).manual_seed(seed)
        image = pipe(
            prompt=prompt,
            negative_prompt=negative,
            num_inference_steps=steps,
            guidance_scale=guidance,
            width=job_width,
            height=job_height,
            generator=generator,
        ).images[0]
        image.save(out_path)
        print(f"saved {out_path}", flush=True)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
