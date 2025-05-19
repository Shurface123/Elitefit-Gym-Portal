export class Chart {
    constructor(ctx, config) {
      console.log("Chart constructor called", ctx, config)
      this.ctx = ctx
      this.config = config
  
      this.render()
    }
  
    render() {
      console.log("Chart render called")
      // A minimal implementation to avoid errors.  A real chart library would do much more here.
      if (this.config.type === "line") {
        // Basic line chart rendering
        this.ctx.beginPath()
        this.ctx.moveTo(50, 50)
        this.ctx.lineTo(250, 150)
        this.ctx.stroke()
      } else if (this.config.type === "bar") {
        // Basic bar chart rendering
        this.ctx.fillRect(50, 50, 100, 100)
      } else if (this.config.type === "doughnut") {
        // Basic doughnut chart rendering
        this.ctx.arc(150, 100, 80, 0, 2 * Math.PI)
        this.ctx.stroke()
      } else {
        this.ctx.fillText("Chart type not supported", 50, 50)
      }
    }
  }
  